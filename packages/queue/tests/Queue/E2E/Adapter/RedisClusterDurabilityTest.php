<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Broker\Redis\Keys;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Result;
use Utopia\Queue\Queue;

final class RedisClusterDurabilityTest extends TestCase
{
    private const array SEEDS = [
        '127.0.0.1:17000',
        '127.0.0.1:17001',
        '127.0.0.1:17002',
    ];

    public function testAtomicPublicationAndClaimsUseOneClusterSlot(): void
    {
        $broker = $this->broker();
        $queue = new Queue(
            'durability-' . bin2hex(random_bytes(8)),
            'queue-cluster',
            visibilityTimeout: 1,
        );

        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'cluster']),
        );
        $this->assertSame(
            Result::Existing,
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'cluster']),
        );

        $first = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $first);

        usleep(1_100_000);

        $second = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertSame($first->getPid(), $second->getPid());

        $broker->commit($queue, $second);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 0));

        $broker->enqueue($queue, ['value' => 'retry']);
        $failed = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $failed);
        $broker->reject($queue, $failed);
        $broker->retry($queue);

        $retried = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $retried);
        $this->assertSame(['value' => 'retry'], $retried->getPayload());
        $broker->commit($queue, $retried);
    }

    public function testCollidingBraceQueueIdentifiersKeepIndependentAuxiliaryState(): void
    {
        $first = new Queue('isolated-3}', 'queue-cluster', visibilityTimeout: 1);
        $second = new Queue('isolated-1640}', 'queue-cluster', visibilityTimeout: 1);
        $firstKeys = Keys::from($first);
        $secondKeys = Keys::from($second);
        $cluster = $this->cluster();
        $this->clear($first);
        $this->clear($second);

        $this->assertSame(
            $this->slot($cluster, $firstKeys->pending),
            $this->slot($cluster, $secondKeys->pending),
        );
        $this->assertNotSame($firstKeys->ledger, $secondKeys->ledger);
        $this->assertCoLocated($cluster, $firstKeys);
        $this->assertCoLocated($cluster, $secondKeys);

        $broker = $this->broker();
        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($first, 'shared-message', ['queue' => 'first']),
        );
        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($second, 'shared-message', ['queue' => 'second']),
        );

        $firstMessage = $broker->receive($first, 0);
        $secondMessage = $broker->receive($second, 0);
        $this->assertInstanceOf(Message::class, $firstMessage);
        $this->assertInstanceOf(Message::class, $secondMessage);
        $this->assertSame('first', $firstMessage->getPayload()['queue']);
        $this->assertSame('second', $secondMessage->getPayload()['queue']);

        $broker->commit($first, $firstMessage);
        $broker->commit($second, $secondMessage);
        $this->clear($first);
        $this->clear($second);
    }

    public function testExplicitHashTagQueuesKeepIndependentDeliveryLifecycles(): void
    {
        $first = new Queue(
            'first{shared}a',
            'dat1993review',
            jobTtl: 5,
            visibilityTimeout: 1,
        );
        $second = new Queue(
            'second{shared}b',
            'dat1993review',
            jobTtl: 5,
            visibilityTimeout: 1,
        );
        $firstKeys = Keys::from($first);
        $secondKeys = Keys::from($second);
        $cluster = $this->cluster();
        $this->clear($first);
        $this->clear($second);

        try {
            $this->assertCoLocated($cluster, $firstKeys);
            $this->assertCoLocated($cluster, $secondKeys);

            foreach (array_keys(\array_slice($this->values($firstKeys), 1, preserve_keys: true)) as $index) {
                $this->assertNotSame(
                    $this->values($firstKeys)[$index],
                    $this->values($secondKeys)[$index],
                );
            }

            $broker = $this->broker();
            $this->assertSame(
                Result::Enqueued,
                $broker->enqueueOnce($first, 'same-id', ['queue' => 'first']),
            );
            $this->assertSame(
                Result::Enqueued,
                $broker->enqueueOnce($second, 'same-id', ['queue' => 'second']),
            );
            $this->assertSame(1, $broker->getQueueSize($first));
            $this->assertSame(1, $broker->getQueueSize($second));

            $firstMessage = $broker->receive($first, 0);
            $secondMessage = $broker->receive($second, 0);
            $this->assertInstanceOf(Message::class, $firstMessage);
            $this->assertInstanceOf(Message::class, $secondMessage);
            $this->assertSame('same-id', $firstMessage->getPid());
            $this->assertSame('same-id', $secondMessage->getPid());
            $this->assertSame(['queue' => 'first'], $firstMessage->getPayload());
            $this->assertSame(['queue' => 'second'], $secondMessage->getPayload());
            $this->assertNotSame($firstMessage->getReceipt(), $secondMessage->getReceipt());

            usleep(600_000);
            $this->assertTrue($broker->renew($first, $firstMessage));
            usleep(600_000);

            $reclaimed = $broker->receive($second, 0);
            $this->assertInstanceOf(Message::class, $reclaimed);
            $this->assertSame($secondMessage->getPid(), $reclaimed->getPid());
            $this->assertNotSame($secondMessage->getReceipt(), $reclaimed->getReceipt());

            $broker->commit($first, $firstMessage);
            $this->assertTrue($broker->renew($second, $reclaimed));
            $broker->reject($second, $reclaimed);

            $this->assertSame(0, $broker->getQueueSize($first, failedJobs: true));
            $this->assertSame(1, $broker->getQueueSize($second, failedJobs: true));

            $broker->retry($second);

            $this->assertSame(0, $broker->getQueueSize($second, failedJobs: true));
            $this->assertSame(1, $broker->getQueueSize($second));

            $retried = $broker->receive($second, 0);
            $this->assertInstanceOf(Message::class, $retried);
            $this->assertSame('same-id', $retried->getPid());
            $this->assertSame(['queue' => 'second'], $retried->getPayload());
            $broker->commit($second, $retried);

            $this->assertNotInstanceOf(Message::class, $broker->receive($first, 0));
            $this->assertNotInstanceOf(Message::class, $broker->receive($second, 0));
        } finally {
            $this->clear($first);
            $this->clear($second);
        }
    }

    private function broker(): RedisBroker
    {
        return new RedisBroker($this->connection(), $this->connection());
    }

    private function connection(): RedisCluster
    {
        return new RedisCluster(self::SEEDS);
    }

    private function cluster(): \RedisCluster
    {
        return new \RedisCluster(
            null,
            self::SEEDS,
            1.5,
            1.5,
        );
    }

    private function clear(Queue $queue): void
    {
        $connection = $this->connection();
        $keys = Keys::from($queue);

        foreach ([
            $keys->pending,
            $keys->ledger,
            $keys->processing,
            $keys->receipts,
            $keys->visibility,
            $keys->expiry,
            $keys->failed,
        ] as $key) {
            $connection->remove($key);
        }
    }

    private function assertCoLocated(\RedisCluster $cluster, Keys $keys): void
    {
        $slot = $this->slot($cluster, $keys->pending);

        foreach ($this->values($keys) as $key) {
            $this->assertSame($slot, $this->slot($cluster, $key));
        }
    }

    private function slot(\RedisCluster $cluster, string $key): int
    {
        return (int) $cluster->rawCommand($key, 'CLUSTER', 'KEYSLOT', $key);
    }

    /**
     * @return list<string>
     */
    private function values(Keys $keys): array
    {
        return [
            $keys->pending,
            $keys->ledger,
            $keys->processing,
            $keys->receipts,
            $keys->visibility,
            $keys->expiry,
            $keys->failed,
        ];
    }
}
