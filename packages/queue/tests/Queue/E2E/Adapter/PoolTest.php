<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Pools\Adapter\Stack as Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Leased;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Publisher\Idempotent;
use Utopia\Queue\Publisher\Result;
use Utopia\Queue\Queue;

final class PoolTest extends Base
{
    protected function getPublisher(): Publisher
    {
        $pool = new UtopiaPool(new Stack(), 'redis', 1, fn(): \Utopia\Queue\Broker\Redis => new RedisBroker(new Redis('127.0.0.1', 16379), new Redis('127.0.0.1', 16379)));

        return new Pool($pool, $pool);
    }

    protected function getQueue(): Queue
    {
        return new Queue('pool');
    }

    public function testDelegatesIdempotentPublicationAndLeaseRenewal(): void
    {
        $broker = $this->getPublisher();
        $queue = new Queue(
            'pool-durable-' . bin2hex(random_bytes(8)),
            visibilityTimeout: 1,
        );

        $this->assertInstanceOf(Idempotent::class, $broker);
        $this->assertInstanceOf(Consumer::class, $broker);
        $this->assertInstanceOf(Leased::class, $broker);

        $this->assertSame(
            Result::Enqueued,
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'pool']),
        );
        $this->assertSame(
            Result::Existing,
            $broker->enqueueOnce($queue, 'message-1', ['value' => 'pool']),
        );

        $message = $broker->receive($queue, 0);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue($broker->renew($queue, $message));
        $broker->commit($queue, $message);
    }
}
