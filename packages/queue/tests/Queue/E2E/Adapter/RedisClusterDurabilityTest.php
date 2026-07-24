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
        $this->clear($first);
        $this->clear($second);

        $this->assertSame($this->slot($firstKeys->pending), $this->slot($secondKeys->pending));
        $this->assertNotSame($firstKeys->ledger, $secondKeys->ledger);

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

    private function broker(): RedisBroker
    {
        return new RedisBroker($this->connection(), $this->connection());
    }

    private function connection(): RedisCluster
    {
        return new RedisCluster([
            '127.0.0.1:17000',
            '127.0.0.1:17001',
            '127.0.0.1:17002',
        ]);
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

    private function slot(string $key): int
    {
        $checksum = 0;
        foreach (unpack('C*', $key) ?: [] as $byte) {
            $checksum ^= $byte << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x8000) !== 0
                    ? (($checksum << 1) ^ 0x1021) & 0xffff
                    : ($checksum << 1) & 0xffff;
            }
        }

        return $checksum % 16_384;
    }
}
