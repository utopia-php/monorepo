<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Claim;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Recoverable;
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

    public function testStopAfterConcurrentClaimLeavesMessageForRecovery(): void
    {
        $this->prepare();
        $publisher = $this->broker();
        $publisher->enqueue($this->queue, ['id' => 'A']);
        $publisher->enqueue($this->queue, ['id' => 'B']);
        $callbacks = [];
        $acks = [];
        $errors = [];

        Coroutine\run(function () use (&$callbacks, &$acks, &$errors): void {
            $consumer = new CoordinatedRecoverableConsumer($this->broker());
            $adapter = new Swoole(
                $consumer,
                1,
                $this->queue->name,
                self::NAMESPACE,
                maxCoroutines: 2,
                reliable: $this->queue->reliable,
            );

            $adapter->consume(
                function (Message $message) use ($adapter, $consumer, &$callbacks): void {
                    $id = $message->getPayload()['id'];
                    $callbacks[] = $id;
                    if ($id !== 'A') {
                        return;
                    }

                    try {
                        $consumer->waitForSecondClaim();
                    } finally {
                        $adapter->stop();
                        $consumer->releaseSecondClaim();
                    }
                },
                static function (Message $message) use (&$acks): void {
                    $acks[] = $message->getPayload()['id'];
                },
                static function (Message $message, \Throwable $error) use (&$errors): void {
                    $errors[] = [$message->getPayload()['id'], $error->getMessage()];
                },
            );
        });

        $this->assertSame(['A'], $callbacks);
        $this->assertSame(['A'], $acks);
        $this->assertSame([], $errors);
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(1, $this->stat('processing'));
        $this->assertSame(1, $this->redis->hLen($this->key('jobs')));
        $this->assertSame(1, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $this->redis->lLen($this->key('queue')));

        $pid = $this->redis->zRange($this->key('processing'), 0, 0)[0] ?? null;
        $this->assertIsString($pid);
        $this->redis->zAdd($this->key('processing'), 0, $pid);
        $broker = $this->broker();
        $claims = $broker->expired($this->queue, 1);
        $this->assertCount(1, $claims);
        $this->assertInstanceOf(Message::class, $broker->reclaim($this->queue, $claims[0]));
        $recovered = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $recovered);
        $this->assertSame(['id' => 'B'], $recovered->getPayload());
        $broker->commit($this->queue, $recovered);

        $this->assertSame(2, $this->stat('success'));
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(0, $this->redis->hLen($this->key('jobs')));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $this->redis->lLen($this->key('queue')));
    }

    #[DataProvider('createFailureProvider')]
    public function testCoroutineCreationFailureUnwindsWithoutProcessingClaimedJob(string $stage): void
    {
        $this->prepare();
        if ($stage !== 'recovery') {
            $this->broker()->enqueue($this->queue, ['stage' => $stage]);
        }

        $result = $this->runCreateFailure($stage);

        $this->assertSame(0, $result['code'], $result['error']);
        $this->assertStringContainsString('handled', $result['output']);
        $this->assertStringNotContainsString('processed', $result['output']);
        if ($stage === 'recovery') {
            $this->assertSame(0, $this->stat('processing'));
            $this->assertSame(0, $this->redis->lLen($this->key('failed')));

            return;
        }

        $this->assertSame(1, $this->stat('processing'));
        $this->assertSame(1, $this->redis->zCard($this->key('processing')));
        $this->assertSame(1, $this->redis->hLen($this->key('jobs')));
        $this->assertSame(0, $this->redis->lLen($this->key('failed')));
        $this->assertSame(0, $this->stat('failed'));
        $this->assertSame(0, $this->stat('success'));
        $this->assertSame(0, $this->redis->lLen($this->key('queue')));

        $pid = $this->redis->zRange($this->key('processing'), 0, 0)[0] ?? null;
        $this->assertIsString($pid);
        $this->redis->zAdd($this->key('processing'), 0, $pid);
        $broker = $this->broker();
        $claims = $broker->expired($this->queue, 1);
        $this->assertCount(1, $claims);
        $this->assertInstanceOf(Message::class, $broker->reclaim($this->queue, $claims[0]));
        $recovered = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $recovered);
        $broker->commit($this->queue, $recovered);

        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $this->redis->hLen($this->key('jobs')));
    }

    public function testLegacyHandlerCreationFailureFallsBackSynchronously(): void
    {
        $this->prepare();
        $legacy = new Queue($this->queue->name, self::NAMESPACE);
        $this->broker()->enqueue($legacy, ['stage' => 'legacy']);

        $result = $this->runCreateFailure('legacy');

        $this->assertSame(0, $result['code'], $result['error']);
        $this->assertStringContainsString('handled', $result['output']);
        $this->assertStringContainsString('processed', $result['output']);
        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->stat('failed'));
        $this->assertSame(0, $this->redis->lLen(self::NAMESPACE . '.processing.' . $this->queue->name));
        $this->assertSame(0, $this->redis->lLen(self::NAMESPACE . '.failed.' . $this->queue->name));
    }

    /** @return iterable<string, array{int}> */
    public static function capacityProvider(): iterable
    {
        yield 'one coroutine' => [1];
        yield 'three coroutines' => [3];
    }

    /** @return iterable<string, array{string}> */
    public static function createFailureProvider(): iterable
    {
        yield 'recovery' => ['recovery'];
        yield 'handler' => ['handler'];
        yield 'heartbeat' => ['heartbeat'];
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

    /** @return array{code: int, output: string, error: string} */
    private function runCreateFailure(string $stage): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/reliable-create-failure.php', $stage, $this->queue->name],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            \dirname(__DIR__, 4),
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $code = null;
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            usleep(10_000);
        }
        if ($code === null) {
            proc_terminate($process);
            usleep(50_000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            $code = 124;
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($code === -1) {
            $code = $closed;
        }

        return ['code' => $code, 'output' => $output, 'error' => $error];
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

final class CoordinatedRecoverableConsumer implements Consumer, Recoverable
{
    private readonly Channel $claimed;
    private readonly Channel $release;
    private int $receives = 0;

    public function __construct(private readonly RedisBroker $consumer)
    {
        $this->claimed = new Channel(1);
        $this->release = new Channel(1);
    }

    public function receive(Queue $queue, int $timeout): ?Message
    {
        $message = $this->consumer->receive($queue, $timeout);
        $this->receives++;
        if ($this->receives === 2 && $message instanceof Message) {
            $this->claimed->push($message);
            $this->release->pop();
        }

        return $message;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->consumer->commit($queue, $message);
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->consumer->reject($queue, $message);
    }

    public function close(): void
    {
        $this->consumer->close();
    }

    public function extend(Queue $queue, Message $message): bool
    {
        return $this->consumer->extend($queue, $message);
    }

    public function expired(Queue $queue, int $limit): array
    {
        return $this->consumer->expired($queue, $limit);
    }

    public function reclaim(Queue $queue, Claim $claim): ?Message
    {
        return $this->consumer->reclaim($queue, $claim);
    }

    public function waitForSecondClaim(): void
    {
        if (!$this->claimed->pop(2.0) instanceof Message) {
            throw new \RuntimeException('Second claim was not completed.');
        }
    }

    public function releaseSecondClaim(): void
    {
        $this->release->push(true);
    }
}
