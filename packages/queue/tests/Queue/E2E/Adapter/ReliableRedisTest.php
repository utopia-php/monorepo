<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Exception\ClaimLost;
use Utopia\Queue\Message;
use Utopia\Queue\Option\Reliable;
use Utopia\Queue\Queue;

final class ReliableRedisTest extends TestCase
{
    private const string NAMESPACE = 'reliable-tests';

    private \Redis $redis;
    private Queue $queue;

    public function testLegacyClaimStillAppliesPerJobTtl(): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', 16379, 1.0);
        $this->queue = new Queue(
            'legacy-ttl-' . bin2hex(random_bytes(6)),
            self::NAMESPACE,
            jobTtl: 30,
        );
        $broker = $this->broker(16379);
        $broker->enqueue($this->queue, ['legacy' => true]);

        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $ttl = $this->redis->ttl(self::NAMESPACE . '.jobs.' . $this->queue->name . '.' . $message->getPid());
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);

        $broker->commit($this->queue, $message);
    }

    #[DataProvider('serverProvider')]
    public function testRejectRetryClaimCommitLifecycleHasExactStateAndStats(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);

        $this->assertTrue($broker->enqueue($this->queue, ['value' => 'payload']));
        $first = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $first);
        $this->assertNotNull($first->getClaimedAt());

        $firstPid = $first->getPid();
        $broker->reject($this->queue, $first);
        $broker->reject($this->queue, $first);

        $this->assertSame(1, $broker->getQueueSize($this->queue, failedJobs: true));
        $this->assertSame(1, $this->stat('failed'));
        $this->assertSame(0, $this->stat('processing'));

        $before = $this->serverSeconds();
        $broker->retry($this->queue, 1);
        $after = $this->serverSeconds();

        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));
        $retried = $this->pending();
        $this->assertSame(['pid', 'queue', 'timestamp', 'payload'], array_keys($retried));
        $this->assertNotSame($firstPid, $retried['pid']);
        $this->assertGreaterThanOrEqual($before, $retried['timestamp']);
        $this->assertLessThanOrEqual($after, $retried['timestamp']);
        $this->assertSame(['value' => 'payload'], $retried['payload']);
        $this->assertFalse($this->redis->hExists($this->key('jobs'), $firstPid));
        $this->assertSame(1, $this->stat('retried'));

        $second = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertNotSame($firstPid, $second->getPid());
        $broker->commit($this->queue, $second);
        $broker->commit($this->queue, $second);
        $broker->reject($this->queue, $second);

        $this->assertSame(2, $this->stat('total'));
        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(1, $this->stat('failed'));
        $this->assertSame(1, $this->stat('retried'));
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->redis->hLen($this->key('jobs')));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));
    }

    #[DataProvider('serverProvider')]
    public function testReliableEnqueuePreservesPriorityAndFifoOrdering(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['order' => 'normal-1']);
        $broker->enqueue($this->queue, ['order' => 'normal-2']);
        $broker->enqueue($this->queue, ['order' => 'priority'], priority: true);

        foreach (['priority', 'normal-1', 'normal-2'] as $expected) {
            $message = $broker->receive($this->queue, 1);
            $this->assertInstanceOf(Message::class, $message);
            $this->assertSame($expected, $message->getPayload()['order']);
            $broker->commit($this->queue, $message);
        }

        $this->assertSame(0, $broker->getQueueSize($this->queue));
        $this->assertSame(3, $this->stat('success'));
    }

    #[DataProvider('serverProvider')]
    public function testReclaimPreservesEnqueueDataUsesNewPidAndPlainEnvelope(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['number' => 42]);

        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $oldPid = $message->getPid();
        $timestamp = $message->getTimestamp();
        $this->redis->zAdd($this->key('processing'), 0, $oldPid);

        $claims = $broker->expired($this->queue, 10);
        $this->assertCount(1, $claims);
        $replacement = $broker->reclaim($this->queue, $claims[0]);

        $this->assertInstanceOf(Message::class, $replacement);
        $this->assertNull($replacement->getClaimedAt());
        $this->assertNotSame($oldPid, $replacement->getPid());
        $this->assertSame($timestamp, $replacement->getTimestamp());
        $this->assertSame(['number' => 42], $replacement->getPayload());
        $this->assertSame(['pid', 'queue', 'timestamp', 'payload'], array_keys($this->pending()));
        $this->assertFalse($this->redis->hExists($this->key('jobs'), $oldPid));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(1, $this->stat('reclaimed'));

        try {
            $broker->commit($this->queue, $message);
            $this->fail('Committing a reclaimed claim must report that ownership was lost.');
        } catch (ClaimLost $error) {
            $this->assertSame($message->getPid(), $error->pid);
            $this->assertSame($message->getClaimedAt(), $error->claimedAt);
        }
        try {
            $broker->reject($this->queue, $message);
            $this->fail('Rejecting a reclaimed claim must report that ownership was lost.');
        } catch (ClaimLost $error) {
            $this->assertSame($message->getPid(), $error->pid);
            $this->assertSame($message->getClaimedAt(), $error->claimedAt);
        }
        $this->assertSame(0, $this->stat('success'));
        $this->assertSame(0, $this->stat('failed'));
        $this->assertSame(0, $this->stat('processing'));

        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->reclaim($this->queue, $claims[0]));
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(1, $this->stat('reclaimed'));

        $claimedAgain = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $claimedAgain);
        $this->assertNotNull($claimedAgain->getClaimedAt());
        $broker->commit($this->queue, $claimedAgain);
    }

    #[DataProvider('serverProvider')]
    public function testForcedReclaimReportsLostClaimWithoutCallingSuccess(int $port): void
    {
        $this->prepare($port, visibility: 3);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['slow' => true]);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $adapter = new ProcessingAdapter(
            $broker,
            1,
            $this->queue->name,
            self::NAMESPACE,
            reliable: $this->queue->reliable,
        );
        $replacement = null;
        $successes = [];
        $errors = [];

        $adapter->processMessage(
            $message,
            function (Message $message) use ($broker, &$replacement): void {
                usleep(50_000);
                $this->redis->zAdd($this->key('processing'), 0, $message->getPid());
                $claim = $broker->expired($this->queue, 1)[0];
                $replacement = $broker->reclaim($this->queue, $claim);
            },
            static function (Message $message) use (&$successes): void {
                $successes[] = $message;
            },
            static function (Message $message, \Throwable $error) use (&$errors): void {
                $errors[] = [$message, $error];
            },
        );

        $this->assertInstanceOf(Message::class, $replacement);
        $this->assertSame([], $successes);
        $this->assertCount(1, $errors);
        $this->assertInstanceOf(ClaimLost::class, $errors[0][1]);
        $this->assertSame(0, $this->stat('success'));
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(1, $this->redis->lLen($this->key('queue')));

        $recovered = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $recovered);
        $broker->commit($this->queue, $recovered);
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->stat('processing'));
    }

    #[DataProvider('serverProvider')]
    public function testHeartbeatRenewsOnlyLeaseAndOriginalTokenStillCommits(int $port): void
    {
        $this->prepare($port, visibility: 2);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['slow' => true]);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);

        $token = $message->getClaimedAt();
        $firstScore = (float) $this->redis->zScore($this->key('processing'), $message->getPid());
        usleep(20_000);
        $this->assertTrue($broker->extend($this->queue, $message));
        $secondScore = (float) $this->redis->zScore($this->key('processing'), $message->getPid());
        usleep(20_000);
        $this->assertTrue($broker->extend($this->queue, $message));

        $record = json_decode((string) $this->redis->hGet($this->key('jobs'), $message->getPid()), true);
        $this->assertSame($token, $message->getClaimedAt());
        $this->assertSame($token, $record['claimedAt']);
        $this->assertGreaterThan($firstScore, $secondScore);
        $this->assertGreaterThan($secondScore, (float) $this->redis->zScore($this->key('processing'), $message->getPid()));

        $broker->commit($this->queue, $message);
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->stat('processing'));
    }

    #[DataProvider('serverProvider')]
    public function testRetryAndReclaimRacesHaveOneWinner(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['winner' => 'reclaim']);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $this->redis->zAdd($this->key('processing'), 0, $message->getPid());
        $claim = $broker->expired($this->queue, 1)[0];

        $this->assertInstanceOf(Message::class, $broker->reclaim($this->queue, $claim));
        try {
            $broker->reject($this->queue, $message);
            $this->fail('The reclaim winner must invalidate the original rejection token.');
        } catch (ClaimLost) {
        }
        $broker->retry($this->queue);
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));

        $this->cleanup();
        $broker->enqueue($this->queue, ['winner' => 'retry']);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $broker->reject($this->queue, $message);
        $broker->retry($this->queue, 1);
        $broker->retry($this->queue, 1);

        $this->assertSame([], $broker->expired($this->queue, 10));
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));
        $this->assertSame(1, $this->stat('retried'));
    }

    #[DataProvider('serverProvider')]
    public function testMalformedPendingAndMissingProcessingStateAreQuarantined(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $this->redis->lPush($this->key('queue'), '{invalid-json');

        $started = hrtime(true);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($this->queue, 1));
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(1.5, $elapsed);
        $this->assertSame(1, $this->redis->lLen($this->key('quarantine')));
        $this->assertSame(1, $this->stat('quarantined'));

        $missingPid = 'missing-pid';
        $this->redis->zAdd($this->key('processing'), 0, $missingPid);
        $this->redis->set($this->statKey('processing'), '1');
        $claims = $broker->expired($this->queue, 10);
        $this->assertCount(1, $claims);
        $this->assertNull($claims[0]->claimedAt);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->reclaim($this->queue, $claims[0]));

        $this->assertSame(2, $this->redis->lLen($this->key('quarantine')));
        $this->assertSame(2, $this->stat('quarantined'));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $this->stat('processing'));
    }

    #[DataProvider('serverProvider')]
    public function testMissingFailedStateIsQuarantinedWithoutBlockingValidRetry(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['valid' => true]);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $broker->reject($this->queue, $message);
        $this->redis->rPush($this->key('failed'), 'missing-pid');

        $broker->retry($this->queue);

        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(1, $this->redis->lLen($this->key('quarantine')));
        $this->assertSame(1, $this->stat('quarantined'));
        $this->assertSame(1, $this->stat('retried'));
    }

    #[DataProvider('serverProvider')]
    public function testMalformedClaimsFillingBatchAreQuarantinedWithoutStarvingValidRecovery(int $port): void
    {
        $batch = 3;
        $this->prepare($port, batch: $batch);
        $broker = $this->broker($port);
        $broker->enqueue($this->queue, ['valid' => true]);
        $valid = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $valid);
        $this->redis->zAdd($this->key('processing'), 1, $valid->getPid());

        $records = [
            'missing-claimed-at' => null,
            'non-string-claimed-at' => 123,
            'empty-claimed-at' => '',
        ];
        foreach ($records as $pid => $claimedAt) {
            $payload = json_encode(['poison' => $pid], JSON_THROW_ON_ERROR);
            $record = [
                'queue' => $this->queue->name,
                'timestamp' => 1,
                'payload' => $payload,
                'state' => 'processing',
                'leaseUntil' => 0,
            ];
            if ($pid !== 'missing-claimed-at') {
                $record['claimedAt'] = $claimedAt;
            }
            $this->redis->hSet($this->key('jobs'), $pid, json_encode($record, JSON_THROW_ON_ERROR));
            $this->redis->zAdd($this->key('processing'), 0, $pid);
        }
        $this->redis->set($this->statKey('processing'), (string) (\count($records) + 1));

        $replacements = [];
        $seen = [];
        for ($scan = 0; $scan < 2; $scan++) {
            $claims = $broker->expired($this->queue, $batch);
            array_push($seen, ...$claims);
            foreach ($claims as $claim) {
                $replacement = $broker->reclaim($this->queue, $claim);
                if ($replacement instanceof Message) {
                    $replacements[] = $replacement;
                }
            }
            if (\count($claims) < $batch) {
                break;
            }
        }

        $this->assertCount(1, $replacements);
        $this->assertSame(['valid' => true], $replacements[0]->getPayload());
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
        $this->assertSame(0, $this->redis->hLen($this->key('jobs')));
        $this->assertSame(3, $this->redis->lLen($this->key('quarantine')));
        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(3, $this->stat('quarantined'));

        foreach ($seen as $claim) {
            $this->assertNotInstanceOf(Message::class, $broker->reclaim($this->queue, $claim));
        }
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(3, $this->stat('quarantined'));
    }

    #[DataProvider('serverProvider')]
    public function testRetryPreservesFailedFifoOrderingAcrossMultipleJobs(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $order = ['first', 'second', 'third'];

        foreach ($order as $value) {
            $broker->enqueue($this->queue, ['order' => $value]);
        }
        foreach ($order as $value) {
            $message = $broker->receive($this->queue, 1);
            $this->assertInstanceOf(Message::class, $message);
            $this->assertSame($value, $message->getPayload()['order']);
            $broker->reject($this->queue, $message);
        }

        $this->assertSame(3, $broker->getQueueSize($this->queue, failedJobs: true));
        $broker->retry($this->queue);
        $this->assertSame(0, $broker->getQueueSize($this->queue, failedJobs: true));

        foreach ($order as $value) {
            $message = $broker->receive($this->queue, 1);
            $this->assertInstanceOf(Message::class, $message);
            $this->assertSame($value, $message->getPayload()['order']);
            $broker->commit($this->queue, $message);
        }

        $this->assertSame(3, $this->stat('retried'));
        $this->assertSame(3, $this->stat('success'));
        $this->assertSame(0, $this->stat('processing'));
    }

    #[DataProvider('serverProvider')]
    public function testPayloadEnvelopeRemainsOpaqueAcrossClaimRetryAndReclaim(int $port): void
    {
        $this->prepare($port);
        $broker = $this->broker($port);
        $payload = [
            'large' => 9_007_199_254_740_993,
            'ordered' => [
                'zebra' => 1,
                'alpha' => 2,
                'middle' => 3,
            ],
        ];
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $broker->enqueue($this->queue, $payload);
        $envelope = $this->redis->lIndex($this->key('queue'), 0);
        $this->assertIsString($envelope);
        $this->assertSame($encodedPayload, $this->encodedPayload($envelope));
        $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($payload, $decoded['payload']);

        $claimed = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $claimed);
        $this->assertSame($payload, $claimed->getPayload());
        $record = json_decode(
            (string) $this->redis->hGet($this->key('jobs'), $claimed->getPid()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertArrayNotHasKey('message', $record);
        $this->assertSame($this->queue->name, $record['queue']);
        $this->assertSame($claimed->getTimestamp(), $record['timestamp']);
        $this->assertSame($encodedPayload, $record['payload']);

        $broker->reject($this->queue, $claimed);
        $before = $this->serverSeconds();
        $broker->retry($this->queue);
        $after = $this->serverSeconds();
        $retried = $this->redis->lIndex($this->key('queue'), 0);
        $this->assertIsString($retried);
        $this->assertSame($encodedPayload, $this->encodedPayload($retried));
        $retriedEnvelope = json_decode($retried, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($claimed->getPid(), $retriedEnvelope['pid']);
        $this->assertGreaterThanOrEqual($before, $retriedEnvelope['timestamp']);
        $this->assertLessThanOrEqual($after, $retriedEnvelope['timestamp']);
        $this->assertSame($payload, $retriedEnvelope['payload']);
        $claimedAgain = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $claimedAgain);
        $this->assertSame($retriedEnvelope['pid'], $claimedAgain->getPid());
        $this->assertSame($payload, $claimedAgain->getPayload());

        $this->redis->zAdd($this->key('processing'), 0, $claimedAgain->getPid());
        $claim = $broker->expired($this->queue, 1)[0];
        $reclaimed = $broker->reclaim($this->queue, $claim);
        $this->assertInstanceOf(Message::class, $reclaimed);
        $this->assertNotSame($claimedAgain->getPid(), $reclaimed->getPid());
        $this->assertSame($claimedAgain->getTimestamp(), $reclaimed->getTimestamp());
        $this->assertSame($payload, $reclaimed->getPayload());
        $reclaimedEnvelope = $this->redis->lIndex($this->key('queue'), 0);
        $this->assertIsString($reclaimedEnvelope);
        $this->assertSame($encodedPayload, $this->encodedPayload($reclaimedEnvelope));
    }

    /** @return iterable<string, array{int}> */
    public static function serverProvider(): iterable
    {
        yield 'Redis' => [16379];
        yield 'Dragonfly' => [16380];
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->cleanup();
            $this->redis->close();
        }
    }

    private function prepare(int $port, int $visibility = 2, int $batch = 100): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', $port, 1.0);
        $name = 'atomic-' . bin2hex(random_bytes(6));
        $this->queue = new Queue(
            $name,
            self::NAMESPACE,
            reliable: new Reliable(visibility: $visibility, heartbeat: 1, scan: 1, batch: $batch),
        );
        $this->cleanup();
    }

    private function broker(int $port): RedisBroker
    {
        return new RedisBroker(
            new RedisConnection('127.0.0.1', $port, connectTimeout: 1.0, readTimeout: 2.0),
            new Locking(new RedisConnection('127.0.0.1', $port, connectTimeout: 1.0, readTimeout: 2.0)),
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

    private function encodedPayload(string $envelope): string
    {
        $marker = '"payload":';
        $offset = strpos($envelope, $marker);
        $this->assertNotFalse($offset);

        return substr($envelope, $offset + \strlen($marker), -1);
    }

    /** @return array{pid: string, queue: string, timestamp: int, payload: array<mixed>} */
    private function pending(): array
    {
        $value = $this->redis->lIndex($this->key('queue'), 0);
        $this->assertIsString($value);
        $decoded = json_decode($value, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function serverSeconds(): int
    {
        $time = $this->redis->time();
        $this->assertIsArray($time);

        return (int) ltrim((string) $time[0], ':');
    }
}

final class ProcessingAdapter extends Adapter
{
    public function processMessage(
        Message $message,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        $this->process($message, $messageCallback, $successCallback, $errorCallback);
    }

    public function start(): self
    {
        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }
}
