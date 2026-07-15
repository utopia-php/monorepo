<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
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

        $broker->commit($this->queue, $message);
        $broker->reject($this->queue, $message);
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
        $broker->reject($this->queue, $message);
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

    private function prepare(int $port, int $visibility = 2): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', $port, 1.0);
        $name = 'atomic-' . bin2hex(random_bytes(6));
        $this->queue = new Queue(
            $name,
            self::NAMESPACE,
            reliable: new Reliable(visibility: $visibility, heartbeat: 1, scan: 1, batch: 100),
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
