<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\Queue\Publisher\Asynchronous;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * Wraps a synchronous publisher and adds asynchronous, background dispatch on
 * top of a Swoole coroutine — so it satisfies both the Synchronous and
 * Asynchronous contracts.
 *
 * enqueue() pushes the publish onto a bounded channel and returns; a reader
 * coroutine loops over the channel and delegates each dispatch to the wrapped
 * synchronous publisher. The channel capacity is the back-pressure bound — once
 * it fills, enqueue() blocks the producing coroutine until the reader drains a
 * slot, so a slow broker throttles producers instead of piling up unbounded
 * work.
 *
 * publish() bypasses the channel and delegates synchronously.
 */
class Background implements Synchronous, Asynchronous
{
    private readonly Channel $channel;

    private readonly WaitGroup $waitGroup;

    private bool $started = false;

    public function __construct(
        private readonly Synchronous $publisher,
        int $capacity = 512,
    ) {
        $this->channel = new Channel(max(1, $capacity));
        $this->waitGroup = new WaitGroup();
    }

    /**
     * Spawn the reader coroutine that drains the channel into the wrapped
     * publisher. Call once from within a coroutine runtime; until then
     * enqueue() publishes synchronously.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->waitGroup->add();

        Coroutine::create(function (): void {
            try {
                while (($task = $this->channel->pop()) instanceof \Closure) {
                    try {
                        $task();
                    } catch (\Throwable $error) {
                        // Fire-and-forget: no producer to surface to, so log and move on.
                        error_log('Uncaught error while publishing queue message: ' . $error->getMessage());
                    }
                }
            } finally {
                $this->waitGroup->done();
            }
        });
    }

    /**
     * Drain the channel and stop the reader, blocking until it has finished.
     * Messages already enqueued are published before the reader exits.
     */
    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        $this->channel->push(null); // sentinel: pop() returns non-Closure → loop ends
        $this->waitGroup->wait();
        $this->started = false;
    }

    /**
     * Publish synchronously, blocking until the broker accepts the message.
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return $this->publisher->publish($queue, $payload, $priority);
    }

    /**
     * Hand the publish to the background reader via the channel, blocking only
     * when the channel is full (back pressure). Falls back to a synchronous
     * publish when no reader loop is running.
     */
    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        if (!$this->started || Coroutine::getCid() === -1) {
            return $this->publish($queue, $payload, $priority);
        }

        return $this->channel->push(function () use ($queue, $payload, $priority): void {
            $this->publisher->publish($queue, $payload, $priority);
        });
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->publisher->retry($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->publisher->getQueueSize($queue, $failedJobs);
    }
}
