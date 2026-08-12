<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * E2E tests for the NATS JetStream broker. Requires a JetStream-enabled server
 * (NATS_URL, default nats://127.0.0.1:14225); skips when unreachable.
 */
final class NatsBrokerTest extends TestCase
{
    private Connection $connection;
    private Nats $broker;
    private Queue $queue;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

        $host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($url, PHP_URL_PORT) ?: 4222;
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("NATS server not reachable at {$url}");
        }
        fclose($probe);

        $this->connection = Connection::connect($url);
        // Short ackWait + low maxDeliver so the redelivery/dead-letter paths are fast.
        $this->broker = new Nats($this->connection, ackWait: 2.0, maxDeliver: 3);
        $this->queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));
    }

    protected function tearDown(): void
    {
        $this->broker->close();
    }

    public function testEnqueueReceiveCommit(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'a']);
        $this->broker->enqueue($this->queue, ['task' => 'b']);
        $this->assertSame(2, $this->broker->getQueueSize($this->queue));

        $message = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('a', $message->getPayload()['task']);

        $this->broker->commit($this->queue, $message);
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
    }

    public function testPriorityMessageJumpsAhead(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'normal']);
        $this->broker->enqueue($this->queue, ['task' => 'urgent'], priority: true);

        $message = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('urgent', $message->getPayload()['task']);
        $this->broker->commit($this->queue, $message);
    }

    public function testRejectRedeliversAndCountsAttempts(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'retryable']);

        $first = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame(0, $first->getAttempts());

        $this->broker->reject($this->queue, $first);

        $second = $this->broker->receive($this->queue, 3);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertSame('retryable', $second->getPayload()['task']);
        $this->assertSame(1, $second->getAttempts());
        $this->broker->commit($this->queue, $second);
    }

    public function testExhaustedMessageIsDeadLetteredThenRetried(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'doomed']);

        // maxDeliver = 3: reject three deliveries; the third exhausts and dead-letters.
        for ($i = 0; $i < 3; $i++) {
            $message = $this->broker->receive($this->queue, 3);
            $this->assertInstanceOf(Message::class, $message);
            $this->broker->reject($this->queue, $message);
        }

        $this->assertSame(1, $this->broker->getQueueSize($this->queue, true), 'message should be on the dead stream');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'work queue should be empty');

        // retry() re-drives the dead stream back onto the work queue.
        $this->broker->retry($this->queue, 10);
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, true));

        $recovered = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $recovered);
        $this->assertSame('doomed', $recovered->getPayload()['task']);
        $this->broker->commit($this->queue, $recovered);
    }
}
