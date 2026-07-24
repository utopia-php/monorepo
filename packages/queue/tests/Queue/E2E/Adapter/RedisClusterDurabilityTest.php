<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as RedisBroker;
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
}
