<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Broker\Async;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

final class AsyncTest extends TestCase
{
    public function testPublishDelegatesSynchronously(): void
    {
        $published = [];
        $async = new Async($this->recordingPublisher($published));

        $result = $async->publish(new Queue('emails'), ['id' => 1]);

        $this->assertTrue($result);
        $this->assertSame([['id' => 1]], $published);
    }

    public function testEnqueueFallsBackToSyncOutsideCoroutine(): void
    {
        $published = [];
        $async = new Async($this->recordingPublisher($published));

        $result = $async->enqueue(new Queue('emails'), ['id' => 1]);

        $this->assertTrue($result);
        $this->assertSame([['id' => 1]], $published, 'no coroutine runtime → publish synchronously');
    }

    public function testEnqueuePublishesOnBackgroundCoroutine(): void
    {
        $published = [];
        $async = new Async($this->recordingPublisher($published));

        // Inside a coroutine runtime enqueue() takes the Coroutine::create()
        // path; the scheduled publishes complete before Coroutine\run() returns.
        Coroutine\run(function () use ($async): void {
            $this->assertTrue($async->enqueue(new Queue('emails'), ['id' => 1]));
            $this->assertTrue($async->enqueue(new Queue('emails'), ['id' => 2]));
        });

        $this->assertSame([['id' => 1], ['id' => 2]], $published);
    }

    public function testDelegatesManagementCalls(): void
    {
        $published = [['id' => 1], ['id' => 2]];
        $async = new Async($this->recordingPublisher($published));

        $this->assertSame(2, $async->getQueueSize(new Queue('emails')));
    }

    /**
     * A Publisher that records enqueued payloads in the given buffer.
     *
     * @param array<int, array<mixed>> $buffer
     */
    private function recordingPublisher(array &$buffer): Publisher
    {
        return new class ($buffer) implements Publisher {
            /**
             * @param array<int, array<mixed>> $buffer
             */
            public function __construct(private array &$buffer) {}

            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->buffer[] = $payload;

                return true;
            }

            public function retry(Queue $queue, ?int $limit = null): void {}

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return \count($this->buffer);
            }
        };
    }
}
