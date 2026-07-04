<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Broker\Async;
use Utopia\Queue\Publisher\Synchronous;
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

    public function testEnqueueFallsBackToSyncWhenNotStarted(): void
    {
        $published = [];
        $async = new Async($this->recordingPublisher($published));

        $result = $async->enqueue(new Queue('emails'), ['id' => 1]);

        $this->assertTrue($result);
        $this->assertSame([['id' => 1]], $published, 'no reader loop running → publish synchronously');
    }

    public function testReaderDrainsChannelIntoPublisher(): void
    {
        $published = [];
        $async = new Async($this->recordingPublisher($published));

        Coroutine\run(function () use ($async): void {
            $async->start();

            for ($i = 1; $i <= 5; $i++) {
                $async->enqueue(new Queue('emails'), ['id' => $i]);
            }

            $async->shutdown(); // drains queued publishes, then waits for the reader
        });

        $this->assertSame([1, 2, 3, 4, 5], array_column($published, 'id'));
    }

    public function testBackPressureBoundDeliversEveryMessageInOrder(): void
    {
        $published = [];
        // Capacity 1 forces enqueue() to block on nearly every push, so the
        // producer only advances as the reader drains — exercising back pressure.
        $async = new Async($this->recordingPublisher($published), capacity: 1);

        Coroutine\run(function () use ($async): void {
            $async->start();

            for ($i = 1; $i <= 20; $i++) {
                $async->enqueue(new Queue('emails'), ['id' => $i]);
            }

            $async->shutdown();
        });

        $this->assertSame(range(1, 20), array_column($published, 'id'));
    }

    public function testDelegatesManagementCalls(): void
    {
        $published = [['id' => 1], ['id' => 2]];
        $async = new Async($this->recordingPublisher($published));

        $this->assertSame(2, $async->getQueueSize(new Queue('emails')));
    }

    /**
     * A synchronous publisher that records published payloads into the buffer.
     *
     * @param array<int, array<mixed>> $buffer
     */
    private function recordingPublisher(array &$buffer): Synchronous
    {
        return new class ($buffer) implements Synchronous {
            /**
             * @param array<int, array<mixed>> $buffer
             */
            public function __construct(private array &$buffer) {}

            public function publish(Queue $queue, array $payload, bool $priority = false): bool
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
